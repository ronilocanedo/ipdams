<?php
declare(strict_types=1);
/**
 * IPDAMS administrative backup export. Read only against application records.
 * All temporary material lives outside the web root; archive downloads require
 * the authenticated Settings Super Administrator session and a valid CSRF token.
 */
const IPDAMS_BACKUP_FILE_LIMIT = 25000;
const IPDAMS_BACKUP_SOURCE_LIMIT = 600000000; // 600 MB of source files.
const IPDAMS_BACKUP_SQL_LIMIT = 175000000; // 175 MB uncompressed SQL.
const IPDAMS_BACKUP_TTL = 3600; // Server-side temporary artifacts, 1 hour.
const IPDAMS_BACKUP_SESSION_TTL = 1200; // Download permission, 20 min.

function ipdamsBackupDir(): string {
    $public=realpath(settingsPublicRoot());
    demand(is_string($public) && is_dir($public),'Public web root not configured',503);
    $privateRoot=realpath(settingsPrivate());
    demand(is_string($privateRoot),'Private configuration storage is unavailable',503);
    // On some Hostinger layouts /private is physically inside /public_html.
    // NEVER put generated full-site archives below a web-served directory.
    $candidate=ipdamsBackupIsUnder($privateRoot,$public)
        ? dirname($public).'/.ipdams-confidential-backups' : $privateRoot.'/export-backups';
    if(!is_dir($candidate) && !@mkdir($candidate,0700,true) && !is_dir($candidate))
        throw new DomainError('Unable to create non-public backup storage. Check Hostinger permissions or use Hostinger manual backups.',503);
    demand(!is_link($candidate) && is_dir($candidate) && !ipdamsBackupIsUnder($candidate,$public),
        'Backup archives cannot be stored inside the public website directory',503);
    @chmod($candidate,0700);
    return $candidate;
}
function ipdamsBackupIsUnder(string $child,string $parent): bool {
    $c=realpath($child);$p=realpath($parent);
    return $c!==false&&$p!==false&&($c===$p||str_starts_with($c,rtrim($p,'/').'/'));
}
function ipdamsBackupZipReady(bool $encryptionRequired=true): void {
    demand(class_exists(ZipArchive::class),'PHP ZIP support unavailable',503);
    if(!$encryptionRequired)return;
    demand(defined('ZipArchive::EM_AES_256') && method_exists(ZipArchive::class,'setEncryptionName'),
        'This host cannot create verified AES-256 encrypted ZIP backups; use your independent Hostinger backup until the PHP ZIP extension supports encryption',503);
}
function ipdamsBackupTime(): string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i:sP');}
function ipdamsBackupClean(): void {
    $dir=ipdamsBackupDir();
    foreach(glob($dir.'/*')?:[] as $path){
        if(is_link($path)||!is_file($path))continue;
        $name=basename($path);
        if(!preg_match('/^(?:[a-f0-9]{24}-(?:website|database|source)\.zip|[a-f0-9]{24}\.json|pending-[a-f0-9]{24}\.tmp)$/',$name))continue;
        if((int)@filemtime($path)<time()-IPDAMS_BACKUP_TTL)@unlink($path);
    }
}
function ipdamsBackupRoots(): array {
    $private=realpath(settingsRoot());$public=realpath(settingsPublicRoot());
    demand(is_string($private)&&is_dir($private)&&is_string($public)&&is_dir($public),'Website directories unavailable',503);
    demand($private!==$public,'Public and private website roots must be separate',503);
    demand(!ipdamsBackupIsUnder(ipdamsBackupDir(),$public),'Backup export directory must be private',503);
    return ['private'=>$private,'public'=>$public];
}
/** Enumerate a pinned path inventory and reject symlinks, traversal, huge files and changing source roots. */
function ipdamsBackupFiles(): array {
    $roots=ipdamsBackupRoots();$out=[];$size=0;
    foreach($roots as $label=>$root){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
        foreach($iterator as $node){
            $path=$node->getPathname();
            // Skip nested private tree if public root is its ancestor; it is inventoried separately.
            if($label==='public' && ipdamsBackupIsUnder($roots['private'],$root) && ipdamsBackupIsUnder($path,$roots['private']))continue;
            // The public directory may instead be nested below the private app
            // root. Never omit its separately mapped website/public/ entries.
            if($label==='private' && ipdamsBackupIsUnder($roots['public'],$root) && ipdamsBackupIsUnder($path,$roots['public']))continue;
            if(is_link($path))throw new DomainError('Website contains a symbolic link; cannot guarantee a complete and secure file backup: '.basename($path),409);
            if(!$node->isFile())continue;
            $real=realpath($path);
            demand(is_string($real)&&ipdamsBackupIsUnder($real,$root),'Unsafe website file path encountered',409);
            $rel=substr($real,strlen(rtrim($root,'/'))+1);
            // Prior backup output, updater staged uploads and recovery archives are excluded.
            if($label==='private' && (str_starts_with($rel,'storage/private/settings/export-backups/')||
                preg_match('#^storage/private/settings/(?:staged-|backup-|db-backup-|reset-archives/)#',$rel)))continue;
            if($label==='private' && str_starts_with($rel,'storage/private/settings/') && str_contains($rel,'.tmp-'))continue;
            demand(!str_contains($rel,'\\')&&!str_contains($rel,'..')&&$rel!=='' ,'Unsafe file entry',409);
            $bytes=$node->getSize();
            demand($bytes>=0 && $bytes<=IPDAMS_BACKUP_SOURCE_LIMIT,'A website file exceeds backup safety limits',409);
            $size+=$bytes;
            demand($size<=IPDAMS_BACKUP_SOURCE_LIMIT,'IPDAMS website exceeds 600 MB safe web-backup limit. Use Hostinger manual export.',409);
            demand(count($out)<IPDAMS_BACKUP_FILE_LIMIT,'Too many website files for one web backup. Use Hostinger manual export.',409);
            demand(is_readable($real),'Website file cannot be read: '.basename($real),409);
            $out[]=['path'=>$real,'entry'=>'website/'.$label.'/'.$rel,'size'=>$bytes,'mtime'=>(int)$node->getMTime()];
        }
    }
    demand(count($out)>0,'No website files found',409);
    usort($out,static fn($a,$b)=>strcmp($a['entry'],$b['entry']));
    return ['files'=>$out,'bytes'=>$size,'roots'=>$roots];
}
function ipdamsBackupAppend(string $path,string $text): void {
    $len=strlen($text);$fp=fopen($path,'ab');demand($fp!==false,'Backup SQL output unavailable',500);
    try{$written=0;while($written<$len){$n=fwrite($fp,substr($text,$written));demand(is_int($n)&&$n>0,'Cannot write complete database backup',500);$written+=$n;}
    }finally{fclose($fp);}
    clearstatcache(true,$path);
    demand((int)filesize($path)<=IPDAMS_BACKUP_SQL_LIMIT,'Database export exceeds shared-hosting backup limit. Use Hostinger SQL export.',409);
}
function ipdamsBackupSqlValue(PDO $db,mixed $value,bool $binary): string {
    if($value===null)return 'NULL';
    if($binary)return '0x'.bin2hex((string)$value);
    $quote=$db->quote((string)$value);
    demand(is_string($quote),'Unable to safely encode database value',500);
    return $quote;
}
function ipdamsBackupTableIdentifier(string $name): string {
    demand((bool)preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$name),'Unsupported database table identifier',409);
    return '`'.$name.'`';
}
function ipdamsBackupDbInventory(PDO $db): array {
    $schema=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    demand($schema!=='','Database schema not selected',503);
    $types=$db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $tables=[];
    foreach($types as $row){
        $kind=strtoupper((string)($row[1]??''));
        demand($kind==='BASE TABLE','Database contains a view or unsupported object; a complete restore-safe export cannot be guaranteed. Use Hostinger SQL export.',409);
        $tables[]=ipdamsBackupTableIdentifier((string)$row[0]);
    }
    demand(count($tables)>0,'No database tables found',409);
    // This exporter deliberately fails closed for triggers/routines/events. Missing
    // database privileges also fail rather than silently producing an incomplete dump.
    foreach(['TRIGGERS'=>'TRIGGER_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $table=>$col){
        $st=$db->prepare('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$col.'=?');
        $st->execute([$schema]);
        demand((int)$st->fetchColumn()===0,'Database contains triggers, procedures or events; use a full Hostinger SQL export for now.',409);
    }
    sort($tables,SORT_STRING);
    return $tables;
}
/**
 * Export schema and snapshot rows under a consistent InnoDB read transaction.
 * Transaction isolation is established before START TRANSACTION; no live rows are changed.
 */
function ipdamsBackupDumpSql(PDO $db,string $path): array {
    demand(!$db->inTransaction(),'Another database transaction is already active',409);
    $tables=ipdamsBackupDbInventory($db);$schema=[];$counts=[];
    foreach($tables as $name){
        $row=$db->query('SHOW CREATE TABLE '.$name)->fetch(PDO::FETCH_NUM);
        demand(is_array($row)&&isset($row[1]),'Unable to capture complete database schema',409);
        $schema[$name]=(string)$row[1];
        demand((bool)preg_match('/ENGINE=InnoDB\b/i',$schema[$name]),'Non-InnoDB database table detected; cannot guarantee a consistent hot backup.',409);
    }
    // The SQL is plaintext until sealed inside the encrypted ZIP. Create an
    // exclusive 0600 file BEFORE writing credentials and financial data.
    $fh=@fopen($path,'xb');
    demand($fh!==false,'Cannot exclusively create private SQL export',503);
    try {
        demand(@chmod($path,0600),'Cannot secure private SQL export',503);
        $heading="-- IPDAMS Database Backup (sensitive; restore to isolated staging before use)\n-- Created ".ipdamsBackupTime()."\nSET NAMES utf8mb4;\nSET @IPDAMS_OLD_SQL_MODE=@@SQL_MODE;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n";
        $written=fwrite($fh,$heading);
        demand($written===strlen($heading),'Cannot write complete SQL export header',503);
    } finally { fclose($fh); }
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try{
        // Materialize the consistent snapshot before scanning any tables.
        $db->query('SELECT COUNT(*) FROM '.$tables[0])->fetchColumn();
        foreach($tables as $table){
            ipdamsBackupAppend($path,"\n-- Table ".$table."\nDROP TABLE IF EXISTS ".$table.";\n".$schema[$table].";\n");
            $cols=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
            $binary=[];
            foreach($cols as $c){
                // Supplying a value for a computed/generated column can make
                // the SQL dump unrestorable. Reject rather than claim success.
                demand(!preg_match('/GENERATED/i',(string)($c['Extra']??'')),
                    'Database contains generated columns unsupported by this exporter; use Hostinger SQL export.',409);
                $binary[(string)$c['Field']]=(bool)preg_match('/^(?:binary|varbinary|blob|tinyblob|mediumblob|longblob|bit)\b/i',(string)$c['Type']);
            }
            $stmt=$db->query('SELECT * FROM '.$table);$n=0;
            while(($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false){
                $names=array_keys($row);
                $qnames=array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`',$names);
                $values=[];
                foreach($row as $col=>$value)$values[]=ipdamsBackupSqlValue($db,$value,$binary[$col]??false);
                ipdamsBackupAppend($path,'INSERT INTO '.$table.' ('.implode(',',$qnames).') VALUES ('.implode(',',$values).');' ."\n");
                $n++;
            }
            $counts[trim($table,'`')]=$n;
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    // A concurrent DDL operation can invalidate the schema/data relationship.
    foreach($tables as $table){
        $row=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
        demand(is_array($row)&&hash_equals(hash('sha256',$schema[$table]),hash('sha256',(string)($row[1]??''))),
            'Database schema changed during backup; export rejected',409);
    }
    ipdamsBackupAppend($path,"\nSET FOREIGN_KEY_CHECKS=1;\nSET SQL_MODE=@IPDAMS_OLD_SQL_MODE;\n");
    return ['tables'=>count($tables),'rows'=>array_sum($counts),'table_counts'=>$counts,'sha256'=>hash_file('sha256',$path),'bytes'=>filesize($path)];
}
function ipdamsBackupEncrypt(ZipArchive $zip,string $name,string $passphrase): void {
    demand($zip->setEncryptionName($name,ZipArchive::EM_AES_256,$passphrase),'ZIP AES-256 encryption failed',503);
    $idx=$zip->locateName($name);
    demand($idx!==false,'ZIP entry was not saved',500);
}
function ipdamsBackupArchive(string $dest,array $items,string $passphrase): array {
    ipdamsBackupZipReady();$zip=new ZipArchive();
    demand($zip->open($dest,ZipArchive::CREATE|ZipArchive::EXCL)===true,'Cannot create backup archive',500);
    $expected=[];
    try{
        foreach($items as $entry=>$file){
            demand(is_file($file)&&!is_link($file),'Backup file changed or became unavailable',409);
            $hash=hash_file('sha256',$file);
            demand(is_string($hash),'Could not hash backup source',500);
            demand($zip->addFile($file,$entry),'Unable to add backup file',500);
            ipdamsBackupEncrypt($zip,$entry,$passphrase);
            $expected[$entry]=$hash;
        }
        demand($zip->close(),'Could not finish backup ZIP',500);
    }catch(Throwable $e){$zip->close();@unlink($dest);throw $e;}
    @chmod($dest,0600);
    // Verify actual encrypted entries can be read back and match original SHA-256.
    $check=new ZipArchive();demand($check->open($dest)===true,'Created backup ZIP is corrupt',500);
    try{
        demand($check->setPassword($passphrase),'Cannot validate encrypted archive password',500);
        demand($check->numFiles===count($expected),'Backup ZIP entry count mismatch',500);
        foreach($expected as $name=>$hash){
            $stream=$check->getStream($name);
            demand(is_resource($stream),'Cannot decrypt and read encrypted archive entry',500);
            $ctx=hash_init('sha256');
            while(!feof($stream)){ $part=fread($stream,1024*1024);demand(is_string($part) && ($part!=='' || feof($stream)),'Archive verification read failed',500);hash_update($ctx,$part); }
            fclose($stream);
            demand(hash_equals($hash,hash_final($ctx)),'Backup ZIP content verification failed',500);
        }
    }finally{$check->close();}
    return ['sha256'=>hash_file('sha256',$dest),'bytes'=>filesize($dest),'entries'=>count($expected)];
}
function ipdamsBackupCreate(PDO $db,int $uid,string $passphrase=''): array {
    // Full recovery can be password-free when explicitly acknowledged by the
    // authorized Super Administrator in Settings. Strongly prefer the optional
    // encrypted mode for off-host storage containing SQL, private files and .env.
    $encrypted=$passphrase!=='';
    if($encrypted)demand(strlen($passphrase)>=16&&strlen($passphrase)<=200,
        'Backup encryption password must be 16–200 characters',422);
    demand(!is_file(settingsPrivate().'/reset-maintenance.lock'),'Data reset in progress; cannot back up safely',503);
    ipdamsBackupZipReady($encrypted);
    $dir=ipdamsBackupDir();$fh=fopen($dir.'/run.lock','c');demand($fh!==false,'Cannot lock backup operations',503);
    if(!flock($fh,LOCK_EX|LOCK_NB)){fclose($fh);throw new DomainError('Another backup is being prepared',409);}
    $id=bin2hex(random_bytes(12));$paths=[
        'website'=>$dir.'/'.$id.'-website.zip','database'=>$dir.'/'.$id.'-database.zip',
        'sql'=>$dir.'/pending-'.$id.'.tmp', 'manifest'=>$dir.'/'.$id.'.json'
    ];
    try{
        ipdamsBackupClean();
        $inventory=ipdamsBackupFiles();
        $free=(int)(@disk_free_space($dir)?:0);
        demand($free>$inventory['bytes']*2+IPDAMS_BACKUP_SQL_LIMIT/3+25_000_000,
            'Not enough verified private disk space for a safe backup. Use Hostinger manual export.',409);
        $sql=ipdamsBackupDumpSql($db,$paths['sql']);
        $sources=[];
        foreach($inventory['files'] as $entry)$sources[$entry['entry']]=$entry['path'];
        $fileResult=$encrypted?ipdamsBackupArchive($paths['website'],$sources,$passphrase):ipdamsBackupArchivePlain($paths['website'],$sources);
        // Ensure none of the source files changed between inventory and completed archive.
        foreach($inventory['files'] as $entry){
            clearstatcache(true,$entry['path']);
            demand(is_file($entry['path']) && !is_link($entry['path']) &&
                filesize($entry['path'])===$entry['size'] && filemtime($entry['path'])===$entry['mtime'],
                'Website files changed during backup. Retry after updates or uploads are idle.',409);
        }
        $sqlResult=$encrypted?ipdamsBackupArchive($paths['database'],['database.sql'=>$paths['sql']],$passphrase):ipdamsBackupArchivePlain($paths['database'],['database.sql'=>$paths['sql']]);
        $manifest=['id'=>$id,'created_at'=>ipdamsBackupTime(),'version'=>settingsState()['version'],
            'status'=>'verified','backup_mode'=>$encrypted?'AES_256_ENCRYPTED_FULL':'PASSWORD_FREE_FULL',
            'website'=>$fileResult+['source_files'=>count($sources),'source_bytes'=>$inventory['bytes']],
            'database'=>$sqlResult+['tables'=>$sql['tables'],'rows'=>$sql['rows'],
                'table_counts'=>$sql['table_counts'],'uncompressed_bytes'=>$sql['bytes']],
            'retention_minutes'=>(int)(IPDAMS_BACKUP_TTL/60),
            'note'=>$encrypted
                ?'AES-256 encrypted recovery. Store BOTH archives and the password separately; test restore on isolated hosting.'
                :'UNENCRYPTED SENSITIVE RECOVERY DATA: both ZIP archives contain credentials and protected personal/financial records. Store BOTH securely, encrypt at rest off-host, and test restore on isolated hosting.'];
        settingsWriteJson($paths['manifest'],$manifest);
        settingsAudit($uid,'backup.created',['id'=>$id,'site_sha256'=>$fileResult['sha256'], 'sql_sha256'=>$sqlResult['sha256'],
            'site_size'=>$fileResult['bytes'],'sql_size'=>$sqlResult['bytes'],'table_count'=>$sql['tables'],
            'backup_mode'=>$manifest['backup_mode']]);
        return $manifest;
    }catch(Throwable $e){foreach($paths as $path){if(is_file($path) && !is_link($path))@unlink($path);}throw $e;
    }finally{if(is_file($paths['sql']))@unlink($paths['sql']);flock($fh,LOCK_UN);fclose($fh);}
}
/**
 * Password-free *source-only* export: it cannot contain credentials, the SQL
 * database, private uploads, settings storage, .env, logs, or personal records.
 * Full disaster-recovery archives can be password-free by explicit Super Admin
 * acknowledgment or AES-256 encrypted by optional password. They always contain
 * sensitive data and must remain inside verified non-public temporary storage.
 */
function ipdamsBackupSourceFiles(): array {
    $inventory=ipdamsBackupFiles();$sources=[];$total=0;
    foreach($inventory['files'] as $file){
        $entry=$file['entry'];
        $allowed=(bool)preg_match('#^website/private/app/[A-Za-z][A-Za-z0-9_-]*\.php$#D',$entry)
            ||(bool)preg_match('#^website/private/db/migrations/[A-Za-z0-9_-]+\.(?:php|sql)$#D',$entry)
            ||(bool)preg_match('#^website/public/(?:app\.html|index\.php|settings\.php|setup\.php|version\.php|settings\.css)$#D',$entry)
            ||(bool)preg_match('#^website/public/api/[A-Za-z][A-Za-z0-9_-]*\.php$#D',$entry)
            ||(bool)preg_match('#^website/public/assets/[A-Za-z0-9_./-]+\.(?:js|css)$#D',$entry);
        if(!$allowed)continue;
        $total+=(int)$file['size'];
        demand($total<=IPDAMS_BACKUP_SOURCE_LIMIT,'Source-only export is too large',409);
        $sources[$entry]=$file['path'];
    }
    demand(count($sources)>0 && isset($sources['website/private/app/Domain.php']) &&
        isset($sources['website/public/app.html']) && isset($sources['website/public/assets/app.js']),
        'Core source files are missing; no incomplete password-free archive will be issued',409);
    return $sources;
}
function ipdamsBackupArchivePlain(string $dest,array $items): array {
    ipdamsBackupZipReady(false);
    $zip=new ZipArchive();
    demand($zip->open($dest,ZipArchive::CREATE|ZipArchive::EXCL)===true,'Cannot create private unencrypted backup archive',503);
    $expected=[];
    try{
        foreach($items as $name=>$path){
            demand(is_file($path)&&!is_link($path),'Backup file became unavailable',409);
            $hash=hash_file('sha256',$path);
            demand(is_string($hash) && $zip->addFile($path,$name),'Cannot include backup file',503);
            $expected[$name]=$hash;
        }
        demand($zip->close(),'Unencrypted ZIP could not be completed',503);
    }catch(Throwable $e){$zip->close();@unlink($dest);throw $e;}
    @chmod($dest,0600);
    $check=new ZipArchive();demand($check->open($dest)===true,'Unencrypted ZIP failed verification',503);
    try{
        demand($check->numFiles===count($expected),'Unencrypted ZIP entry count mismatch',503);
        foreach($expected as $name=>$hash){
            $stream=$check->getStream($name);demand(is_resource($stream),'Unencrypted ZIP member missing',503);
            $ctx=hash_init('sha256');
            while(!feof($stream)){
                $data=fread($stream,1024*1024);
                demand(is_string($data)&&($data!==''||feof($stream)),'Unencrypted ZIP verification read failed',503);
                hash_update($ctx,$data);
            }
            fclose($stream);
            demand(hash_equals($hash,hash_final($ctx)),'Unencrypted ZIP content changed',503);
        }
    }finally{$check->close();}
    return ['sha256'=>hash_file('sha256',$dest),'bytes'=>filesize($dest),'entries'=>count($expected)];
}
function ipdamsBackupCreateSourceOnly(int $uid): array {
    demand(!is_file(settingsPrivate().'/reset-maintenance.lock'),
        'Data reset in progress; do not export source during maintenance.',503);
    $dir=ipdamsBackupDir();$fh=fopen($dir.'/run.lock','c');
    demand($fh!==false&&flock($fh,LOCK_EX|LOCK_NB),'Another backup is being prepared',409);
    $id=bin2hex(random_bytes(12));$file=$dir.'/'.$id.'-source.zip';$manifest=$dir.'/'.$id.'.json';
    try{
        ipdamsBackupClean();$sources=ipdamsBackupSourceFiles();
        $res=ipdamsBackupArchivePlain($file,$sources);
        $result=['id'=>$id,'created_at'=>ipdamsBackupTime(),'version'=>settingsState()['version'],
            'status'=>'verified','backup_mode'=>'PASSWORD_FREE_SANITIZED_SOURCE','source'=>$res,
            'retention_minutes'=>(int)(IPDAMS_BACKUP_TTL/60),
            'note'=>'No ZIP password. Contains only allowlisted app source; no .env, database, credentials, uploads or private records. Keep it secure.'];
        settingsWriteJson($manifest,$result);
        settingsAudit($uid,'backup.source_only.created',['id'=>$id,'source_sha256'=>$res['sha256'],'files'=>$res['entries']]);
        return $result;
    }catch(Throwable $e){if(is_file($file))@unlink($file);if(is_file($manifest))@unlink($manifest);throw $e;
    }finally{flock($fh,LOCK_UN);fclose($fh);}
}
function ipdamsBackupDownload(int $uid,string $id,string $kind): never {
    demand((bool)preg_match('/^[a-f0-9]{24}$/D',$id),'Invalid backup reference',400);
    demand(in_array($kind,['website','database','source'],true),'Invalid backup type',400);
    demand(($_SESSION['backup_access_id']??'')===$id &&
        (int)($_SESSION['backup_access_uid']??0)===$uid &&
        (int)($_SESSION['backup_access_expires']??0)>=time(),
        'Backup download session expired or administrator changed; create a new backup',403);
    $metaFile=ipdamsBackupDir().'/'.$id.'.json';
    demand(is_file($metaFile)&&!is_link($metaFile),'Backup manifest missing',404);
    $m=json_decode((string)file_get_contents($metaFile),true);
    demand(is_array($m)&&($m['id']??'')===$id&&($m['status']??'')==='verified','Backup manifest invalid',409);
    $file=ipdamsBackupDir().'/'.$id.'-'.$kind.'.zip';
    demand(is_file($file)&&!is_link($file),'Backup file unavailable or expired',404);
    demand(hash_equals((string)$m[$kind]['sha256'],(string)hash_file('sha256',$file)),'Backup file checksum mismatch',409);
    settingsAudit($uid,'backup.download',['id'=>$id,'part'=>$kind]);
    session_write_close();
    while(ob_get_level()>0)ob_end_clean();
    header('Content-Type: application/zip');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, max-age=0');
    header('Content-Disposition: attachment; filename="IPDAMS_'.($kind==='website'?'Website':($kind==='source'?'SourceOnly':'Database')).'_'.date('Ymd_His').'.zip"');
    header('Content-Length: '.(string)filesize($file));
    readfile($file);exit;
}
