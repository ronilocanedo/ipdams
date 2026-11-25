<?php
declare(strict_types=1);
/** IPDAMS v0.2.406: global maintenance policy. File based by design: no DB migration,
 * and the scheduled lock is evaluated on EVERY authenticated request without cron.
 * This directory is below private/, never publicly served.
 */
function maintenanceStateFile(): string { return dirname(__DIR__).'/storage/private/maintenance/state.json'; }
function maintenanceDefaults(): array {
    return [
        'mode'=>'OFF','starts_at'=>'','repeat'=>'NONE','auto_activate'=>true,
        'duration_minutes'=>0,'lead_minutes'=>60,'notice_lead_days'=>null,
        'notice_auto_publish'=>true,'notice_manual_enabled'=>false,
        'header'=>'UPCOMING SYSTEM UPDATE',
        'message'=>'There will be an upcoming system update in [countdown]. Please save and complete your work.',
        'active_header'=>'SYSTEM MAINTENANCE',
        'active_message'=>'IPDAMS is temporarily unavailable while system maintenance is in progress.',
        'presets'=>[
            ['name'=>'System update','header'=>'UPCOMING SYSTEM UPDATE','message'=>'There will be an upcoming system update in [countdown]. Please complete and save your work.'],
            ['name'=>'Scheduled maintenance','header'=>'SCHEDULED MAINTENANCE','message'=>'IPDAMS maintenance begins in [countdown]. Please finish ongoing transactions before the deadline.'],
        ],'revision'=>0
    ];
}
function maintenanceRead(): array {
    $file=maintenanceStateFile();
    if(!file_exists($file))return maintenanceDefaults();
    // Corruption/tampering must never silently disable an existing lock.
    if(is_link($file)||!is_file($file)||!is_readable($file))return array_replace(maintenanceDefaults(),['mode'=>'ACTIVE','storage_error'=>true]);
    try{
        $raw=file_get_contents($file);
        $config=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($config)||!in_array($config['mode']??null,['OFF','SCHEDULED','ACTIVE'],true))throw new RuntimeException('Invalid maintenance policy');
        return array_replace(maintenanceDefaults(),$config);
    }catch(Throwable $e){error_log('IPDAMS maintenance state unreadable');return array_replace(maintenanceDefaults(),['mode'=>'ACTIVE','storage_error'=>true]);}
}
function maintenanceTime(string $value): int {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00\+08:00$/D',$value))return 0;
    try{$date=new DateTimeImmutable($value);return $date->getTimestamp();}catch(Throwable){return 0;}
}
/** Calendar-anchored recurrence: 31 January -> 28/29 February -> 31 March.
 * The original day-of-month and clock time are preserved whenever possible.
 * All recurring timestamps use Asia/Manila; server clock is authoritative.
 */
function maintenanceCalendarOccurrence(int $start,int $monthOffset): int {
    $tz=new DateTimeZone('Asia/Manila');
    $origin=(new DateTimeImmutable('@'.$start))->setTimezone($tz);
    $first=$origin->setDate((int)$origin->format('Y'),(int)$origin->format('n'),1);
    $month=$first->modify('+'.$monthOffset.' months');
    $last=(int)$month->format('t');
    return $month->setDate((int)$month->format('Y'),(int)$month->format('n'),min((int)$origin->format('j'),$last))
        ->setTime((int)$origin->format('H'),(int)$origin->format('i'),0)->getTimestamp();
}
function maintenanceNearestOccurrence(int $start,string $repeat,int $now): array {
    if($now<$start)return [null,$start];
    if($repeat==='NONE')return [$start,null];
    $seconds=match($repeat){'DAILY'=>86400,'WEEKLY'=>604800,default=>0};
    if($seconds>0){
        $n=intdiv($now-$start,$seconds);$prev=$start+$n*$seconds;
        return [$prev,$prev+$seconds];
    }
    $months=match($repeat){'MONTHLY'=>1,'QUARTERLY'=>3,'SEMIANNUAL'=>6,'ANNUAL'=>12,default=>0};
    if($months===0)return [null,$start];
    $tz=new DateTimeZone('Asia/Manila');
    $anchor=(new DateTimeImmutable('@'.$start))->setTimezone($tz);
    $current=(new DateTimeImmutable('@'.$now))->setTimezone($tz);
    $diff=((int)$current->format('Y')-(int)$anchor->format('Y'))*12
        +((int)$current->format('n')-(int)$anchor->format('n'));
    $n=max(0,intdiv(max(0,$diff),$months));
    $candidate=maintenanceCalendarOccurrence($start,$n*$months);
    if($candidate>$now&&$n>0){--$n;$candidate=maintenanceCalendarOccurrence($start,$n*$months);}
    if($candidate>$now)return [null,$candidate];
    return [$candidate,maintenanceCalendarOccurrence($start,($n+1)*$months)];
}
/** Pure scheduler: announcement timing and server-enforced lock are independent.
 * Old 0.2.406 saved schedules retain their minute precision until resaved.
 */
function maintenanceEvaluate(array $state,?int $now=null): array {
    $now??=time();$mode=(string)($state['mode']??'OFF');
    $result=['status'=>'OFF','active'=>false,'show_notice'=>false,'countdown_seconds'=>null,'starts_at'=>null,'ends_at'=>null,'server_now'=>$now];
    if(($state['storage_error']??false)===true||$mode==='ACTIVE')
        return array_replace($result,['status'=>'ACTIVE','active'=>true,'show_notice'=>true]);
    if($mode==='OFF')return $result;
    $start=maintenanceTime((string)($state['starts_at']??''));
    if($start<=0)return array_replace($result,['status'=>'ACTIVE','active'=>true,'show_notice'=>true]);
    $repeat=(string)($state['repeat']??'NONE');
    if(!in_array($repeat,['NONE','DAILY','WEEKLY','MONTHLY','QUARTERLY','SEMIANNUAL','ANNUAL'],true))
        return array_replace($result,['status'=>'ACTIVE','active'=>true,'show_notice'=>true]);
    $duration=max(0,(int)($state['duration_minutes']??0))*60;
    $days=$state['notice_lead_days']??null;
    $lead=($days!==null)?max(1,min(365,(int)$days))*86400:max(0,min(525600,(int)($state['lead_minutes']??60)))*60;
    $noticeAuto=($state['notice_auto_publish']??true)===true;
    $noticeManual=($state['notice_manual_enabled']??false)===true;
    $auto=($state['auto_activate']??false)===true;
    [$previous,$next]=maintenanceNearestOccurrence($start,$repeat,$now);
    if($previous!==null){
        $end=$duration>0?$previous+$duration:null;
        $stillActive=$duration===0&&$repeat==='NONE'||($end!==null&&$now<$end);
        if($auto&&$stillActive){
            return array_replace($result,['status'=>'ACTIVE','active'=>true,'show_notice'=>true,'starts_at'=>gmdate('c',$previous),'ends_at'=>$end?gmdate('c',$end):null]);
        }
        if($repeat==='NONE'){
            return array_replace($result,['status'=>$auto?'COMPLETED':'WAITING_OWNER','show_notice'=>!$auto&&($noticeAuto||$noticeManual),'starts_at'=>gmdate('c',$start),'countdown_seconds'=>0]);
        }
    }
    $remaining=max(0,$next-$now);
    $show=$noticeManual||($noticeAuto&&$remaining<=$lead);
    return array_replace($result,['status'=>$show?'COUNTDOWN':'SCHEDULED','show_notice'=>$show,'countdown_seconds'=>$remaining,'starts_at'=>gmdate('c',$next)]);
}
function maintenancePublicState(?int $now=null): array {
    $state=maintenanceRead();$calc=maintenanceEvaluate($state,$now);
    if(($state['storage_error']??false)===true || (string)$state['mode']==='ACTIVE')
        $calc=array_replace($calc,['status'=>'ACTIVE','active'=>true,'show_notice'=>true]);
    return $calc+[
        'header'=>(string)$state['header'],'message'=>(string)$state['message'],
        'active_header'=>(string)$state['active_header'],'active_message'=>(string)$state['active_message'],
        'auto_activate'=>($state['auto_activate']??false)===true,
    ];
}
/** Match the already established, immutable System Branding owner identity. */
function maintenanceIsOwner(PDO $db,array $user): bool {
    if(($user['role']??'')!=='SUPER_ADMIN'||(int)($user['id']??0)<1)return false;
    $file=dirname(__DIR__).'/storage/private/branding/owner.json';
    if(is_link($file))return false;
    if(is_file($file)){
        $saved=json_decode((string)file_get_contents($file),true);
        return is_array($saved)&&is_int($saved['user_id']??null)&&(int)$saved['user_id']===(int)$user['id'];
    }
    $first=one($db,'SELECT id FROM users ORDER BY id ASC LIMIT 1');
    return $first!==null&&(int)$first['id']===(int)$user['id'];
}
function maintenanceRequireOwner(PDO $db,array $user): void {
    demand(maintenanceIsOwner($db,$user),'Only the original IPDAMS owner can manage maintenance',403);
}
function maintenanceGuard(PDO $db,array $user): void {
    if(maintenancePublicState()['active'] && !maintenanceIsOwner($db,$user))
        throw new DomainError('IPDAMS is temporarily locked for scheduled maintenance',503);
}
function maintenanceSaveAtomic(array $new): void {
    $path=maintenanceStateFile();$dir=dirname($path);
    if(is_link($dir))throw new RuntimeException('Unsafe maintenance directory');
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Maintenance storage unavailable');
    if(is_link($path))throw new RuntimeException('Unsafe maintenance policy');
    $lock=fopen($dir.'/.state.lock','c');
    if($lock===false)throw new RuntimeException('Maintenance lock unavailable');
    try{
        if(!flock($lock,LOCK_EX))throw new RuntimeException('Maintenance state lock unavailable');
        $old=maintenanceRead();
        if(($old['storage_error']??false)===true)throw new RuntimeException('Maintenance state needs technical recovery');
        $expected=$new['_expected_revision']??null;unset($new['_expected_revision']);
        demand(is_int($expected)&&(int)$old['revision']===$expected,'Maintenance settings changed in another session. Refresh.',409);
        $new['revision']=$expected+1;
        $tmp=$dir.'/.staging-'.bin2hex(random_bytes(12));
        $json=json_encode($new,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Could not stage maintenance policy');
        @chmod($tmp,0600);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not save maintenance policy');}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function maintenanceValidatedText(mixed $input,int $max,string $name): string {
    demand(is_string($input),'Invalid '.$name,422);
    $value=trim($input);
    demand($value!==''&&mbSafeLen($value)<=$max,'Invalid '.$name.' (1–'.$max.' characters)',422);
    demand(!preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value),'Invalid control characters',422);
    return $value;
}
function maintenanceUpdate(array $post,int $uid): string {
    $action=(string)($post['action']??'');
    $state=maintenanceRead();$expected=(int)($post['revision']??-1);
    demand((string)$expected===(string)($post['revision']??''),'Refresh maintenance settings before saving',409);
    $state['_expected_revision']=$expected;
    if($action==='maintenance_schedule'){
        $start=(string)($post['starts_at']??'');
        demand(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$start)===1,'Select a valid start date/time in Manila time',422);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$start,new DateTimeZone('Asia/Manila'));
        demand($date instanceof DateTimeImmutable && $date->format('Y-m-d\TH:i')===$start,'Invalid maintenance date/time',422);
        demand($date->getTimestamp()>time()+59,'Start time must be at least one minute in the future',422);
        $repeat=(string)($post['repeat']??'NONE');demand(in_array($repeat,['NONE','DAILY','WEEKLY','MONTHLY','QUARTERLY','SEMIANNUAL','ANNUAL'],true),'Invalid recurrence',422);
        $duration=(int)($post['duration_minutes']??0);
        demand($duration>=0&&$duration<=1440&&($repeat==='NONE'||$duration>=1),'Recurring maintenance needs a 1–1440 minute duration',422);
        demand(isset($post['notice_lead_days'])&&preg_match('/^\d{1,3}$/D',(string)$post['notice_lead_days'])===1,'Specify notice days as a whole number',422);
        $leadDays=(int)$post['notice_lead_days'];demand($leadDays>=1&&$leadDays<=365,'Advance notice must be between 1 and 365 days',422);
        $state['mode']='SCHEDULED';$state['starts_at']=$date->format('Y-m-d\TH:i:00P');$state['repeat']=$repeat;
        $state['duration_minutes']=$duration;$state['notice_lead_days']=$leadDays;
        $state['lead_minutes']=$leadDays*1440; // retain backwards-compatible stored field
        $state['notice_auto_publish']=isset($post['notice_auto_publish'])&&$post['notice_auto_publish']==='1';
        $state['notice_manual_enabled']=isset($post['notice_manual_enabled'])&&$post['notice_manual_enabled']==='1';
        $state['auto_activate']=isset($post['auto_activate'])&&$post['auto_activate']==='1';
        $state['header']=maintenanceValidatedText($post['header']??'',100,'announcement header');
        $state['message']=maintenanceValidatedText($post['message']??'',600,'announcement message');
        $state['active_header']=maintenanceValidatedText($post['active_header']??'',100,'maintenance page header');
        $state['active_message']=maintenanceValidatedText($post['active_message']??'',600,'maintenance page message');
        $response='Maintenance schedule and announcement saved.';
    }elseif($action==='maintenance_enable_now'){
        $state['mode']='ACTIVE';$response='Maintenance lock activated immediately.';
    }elseif($action==='maintenance_disable'){
        $state['mode']='OFF';$response='Maintenance lock and future schedule disabled.';
    }elseif($action==='maintenance_preset_save'){
        $name=maintenanceValidatedText($post['preset_name']??'',48,'preset name');
        $header=maintenanceValidatedText($post['preset_header']??'',100,'preset header');
        $message=maintenanceValidatedText($post['preset_message']??'',600,'preset message');
        $presets=array_values(array_filter($state['presets']??[],static fn($p)=>is_array($p)&&strtolower((string)($p['name']??''))!==strtolower($name)));
        demand(count($presets)<12,'Maximum twelve announcement presets',422);
        $presets[]=['name'=>$name,'header'=>$header,'message'=>$message];$state['presets']=$presets;
        $response='Maintenance announcement preset saved.';
    }elseif($action==='maintenance_preset_delete'){
        $name=maintenanceValidatedText($post['preset_name']??'',48,'preset name');
        $state['presets']=array_values(array_filter($state['presets']??[],static fn($p)=>is_array($p)&&($p['name']??'')!==$name));
        $response='Announcement preset removed.';
    }else throw new DomainError('Unknown maintenance action',405);
    maintenanceSaveAtomic($state);
    if(function_exists('settingsAudit'))settingsAudit($uid,'maintenance.'.$action,[
        'revision'=>$expected+1,'mode'=>$state['mode'],'auto_activate'=>$state['auto_activate'],
        'repeat'=>$state['repeat'],'starts_at'=>$state['starts_at'],
        'notice_lead_days'=>$state['notice_lead_days']??null,
        'notice_auto_publish'=>$state['notice_auto_publish']??true]);
    return $response;
}
