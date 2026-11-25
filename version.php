<?php
declare(strict_types=1);
require_once __DIR__.'/private/app/SettingsService.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try{$info=settingsState();echo json_encode(['version'=>$info['version']],JSON_THROW_ON_ERROR);}
catch(Throwable $e){http_response_code(503);echo '{"error":"Version information unavailable"}';}
