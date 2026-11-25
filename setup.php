<?php
declare(strict_types=1);
require_once __DIR__.'/private/app/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
if (!$https && cfg('APP_ENV')==='production') { http_response_code(403); exit('HTTPS is required.'); }
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSETUP'); session_start();
$_SESSION['setup_csrf']??=bin2hex(random_bytes(32));
$error=''; $success=false; $available=false;
try {
  $db=database();
  $secret=cfg('INSTALL_SETUP_TOKEN');
  // A deliberately configured secret is required; placeholders are never accepted.
  $configured=strlen($secret)>=24 && !in_array($secret,['CHANGE_ME','CHANGE_ME_UNIQUE_STRONG_PASSWORD','YOUR_RANDOM_INSTALL_TOKEN'],true);
  $done=is_file(IPDAMS_ROOT.'/storage/private/initial-setup.complete');
  $count=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
  $available=$configured && !$done && $count===0;
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$available) throw new RuntimeException('First-time setup is not available.');
    $attempts=(int)($_SESSION['setup_attempts']??0);
    if($attempts>=8) throw new RuntimeException('Too many attempts. Close this session and contact the system administrator.');
    if(!hash_equals($_SESSION['setup_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Invalid request token. Refresh and try again.');
    $submitted=(string)($_POST['install_token']??'');
    if(!hash_equals($secret,$submitted)){$_SESSION['setup_attempts']=$attempts+1;throw new RuntimeException('Incorrect installation secret.');}
    $email=strtolower(trim((string)($_POST['email']??'')));
    $name=trim((string)($_POST['name']??''));
    $password=(string)($_POST['password']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190) throw new RuntimeException('Enter a valid administrator email.');
    if($name===''||strlen($name)>150) throw new RuntimeException('Enter the administrator full name (maximum 150 characters).');
    if(strlen($password)<12||strlen($password)>256) throw new RuntimeException('Use a password of 12 to 256 characters.');
    if(!hash_equals($password,(string)($_POST['confirm']??''))) throw new RuntimeException('Password confirmation does not match.');
    $lockDir=IPDAMS_ROOT.'/storage/private';
    if(!is_dir($lockDir)||!is_writable($lockDir)) throw new RuntimeException('Private storage is not writable; setup cannot continue.');
    $fp=fopen($lockDir.'/initial-setup.lock','c');
    if(!$fp||!flock($fp,LOCK_EX)) throw new RuntimeException('Could not acquire setup lock.');
    try {
      // Recheck inside the exclusive lock so parallel setup requests cannot create two admins.
      clearstatcache();
      if(is_file($lockDir.'/initial-setup.complete')||(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) throw new RuntimeException('Initial setup was already completed.');
      $db->beginTransaction();
      $st=$db->prepare("INSERT INTO users(email,password_hash,full_name,role,active) VALUES (?,?,?,'SUPER_ADMIN',1)");
      $st->execute([$email,password_hash($password,PASSWORD_DEFAULT),$name]);
      $db->commit();
      // Secondary safety marker: even if this write fails, non-empty users still prevents rerun.
      @file_put_contents($lockDir.'/initial-setup.complete',date(DATE_ATOM)."\n",LOCK_EX);
      $success=true; $available=false; unset($_SESSION['setup_attempts']);
    } finally { flock($fp,LOCK_UN);fclose($fp); }
  }
} catch (Throwable $e) {
  if(isset($db)&&$db instanceof PDO&&$db->inTransaction()) $db->rollBack();
  if ($e instanceof PDOException) { error_log('IPDAMS setup DB error: '.$e->getMessage()); $error='Database error. Check the migration and database configuration.'; }
  else $error=$e->getMessage();
}
function out(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS — Initial Administrator Setup</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f2f7f8;font:16px system-ui,Arial;color:#12313d}.card{box-sizing:border-box;width:min(95vw,480px);background:#fff;padding:36px;border-radius:16px;box-shadow:0 14px 45px #10334419}h1{font-size:25px;margin:0 0 10px}p{line-height:1.5;color:#496370}label{display:block;font-size:14px;font-weight:650;margin:15px 0 5px}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #b8cbd1;border-radius:8px;font:inherit}button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:9px;background:#119f91;color:white;font-weight:700;font-size:16px;cursor:pointer}.err{background:#ffeded;color:#9c2727;padding:12px;border-radius:8px}.ok{background:#e9f8f2;color:#145b46;padding:12px;border-radius:8px}a{color:#087f75}.hint{font-size:13px}</style></head><body><main class="card"><p style="font-size:12px;letter-spacing:.12em;color:#088d80;font-weight:800">IPDAMS • FIRST-TIME INSTALLATION</p><h1>Create Super Administrator</h1>
<?php if($success): ?><div class="ok">Administrator account created. You can now sign in.</div><p>For extra protection, remove the <code>INSTALL_SETUP_TOKEN</code> line from <code>private/.env</code>. Do not remove your database settings.</p><p><a href="./">Go to IPDAMS login →</a></p>
<?php elseif(!$available): ?><div class="err">Setup is unavailable or already completed.</div><p class="hint">For an empty installation, first set <code>INSTALL_SETUP_TOKEN</code> in <code>private/.env</code> to a unique random secret of at least 24 characters. If users already exist, use normal login instead.</p><p><a href="./">Return to login</a></p>
<?php else: ?><p>Use the one-time secret configured in <code>private/.env</code>. This page works only while there are no user accounts.</p><?php if($error): ?><div class="err"><?=out($error)?></div><?php endif; ?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=out($_SESSION['setup_csrf'])?>"><label for="install_token">One-time installation secret</label><input type="password" id="install_token" name="install_token" required autocomplete="off"><label for="name">Administrator full name</label><input id="name" name="name" maxlength="150" required><label for="email">Administrator email</label><input type="email" id="email" name="email" maxlength="190" required><label for="password">New password (12+ characters)</label><input type="password" id="password" name="password" minlength="12" required autocomplete="new-password"><label for="confirm">Confirm password</label><input type="password" id="confirm" name="confirm" minlength="12" required autocomplete="new-password"><button type="submit">Create Administrator</button></form><?php endif; ?></main></body></html>
