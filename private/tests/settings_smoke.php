<?php
declare(strict_types=1);
require __DIR__.'/../app/SettingsService.php';
$allowed=['public/app.html','public/assets/app.js','public/api/index.php','app/Domain.php'];
$rejected=['.env','db/migrations/001_initial.sql','public/settings.php','app/SettingsService.php','app/bootstrap.php','../../etc/passwd','public/assets/../../x.php'];
foreach($allowed as $p){if(!settingsAllowed($p))throw new RuntimeException('Unexpected deny '.$p);}
foreach($rejected as $p){if(settingsAllowed($p))throw new RuntimeException('Unexpected allow '.$p);}
echo 'PASS settings update allowlist: '.count($allowed).' allowed / '.count($rejected).' blocked'.PHP_EOL;
