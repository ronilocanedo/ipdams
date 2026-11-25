<?php
declare(strict_types=1);
require __DIR__.'/../app/SettingsService.php';

// Ordinary release paths are permitted without the additional critical-code flag.
$ordinaryAllowed = [
    'public/app.html',
    'public/assets/app.js',
    'public/api/index.php',
    'app/Domain.php',
];

// These paths are intentionally updateable, but must be classified as critical.
$criticalAllowed = [
    'public/settings.php',
    'public/index.php',
    'public/version.php',
    'app/SettingsService.php',
    'app/bootstrap.php',
];

// Untrusted input paths and protected code/configuration must remain blocked.
$rejected = [
    '.env',
    'public/.env',
    'db/migrations/001_initial.sql',
    'app/ReleaseVersion.php',
    'public/assets/evil.php',
    'public/api/evil.json',
    '../settings.php',
    '../../etc/passwd',
    '/etc/passwd',
    'public/assets/../../x.php',
    'public/settings.php/../x.php',
    'public\\settings.php',
];

foreach ($ordinaryAllowed as $path) {
    if (!settingsAllowed($path)) throw new RuntimeException('Unexpected deny '.$path);
    if (settingsCritical($path)) throw new RuntimeException('Unexpected critical classification '.$path);
}
foreach ($criticalAllowed as $path) {
    if (!settingsAllowed($path)) throw new RuntimeException('Unexpected deny critical '.$path);
    if (!settingsCritical($path)) throw new RuntimeException('Critical path missing review flag '.$path);
}
foreach ($rejected as $path) {
    if (settingsAllowed($path)) throw new RuntimeException('Unexpected allow '.$path);
}

// The admin settings controller must continue to enforce the additional
// critical-code acknowledgement on the SERVER, not only as an HTML checkbox.
$controllerPath = dirname(__DIR__, 2).'/settings.php';
$controller = file_get_contents($controllerPath);
if ($controller === false) throw new RuntimeException('Settings controller unavailable for guard test');
if (!preg_match('/\$critical\s*=\s*array_filter\s*\(\s*array_keys\s*\(\s*\$m\[\s*[\'\"]files[\'\"]\s*\]\s*\)\s*,\s*[\'\"]settingsCritical[\'\"]\s*\)/', $controller)) {
    throw new RuntimeException('Server critical-path classification guard missing');
}
if (!preg_match('/if\s*\(\s*\$critical\s*\)\s*demand\s*\(\s*\(\s*\$_POST\[\s*[\'\"]critical_reviewed[\'\"]\s*\]\s*\?\?\s*[\'\"]{2}\s*\)\s*===\s*[\'\"]1[\'\"]/', $controller)) {
    throw new RuntimeException('Server critical-code acknowledgement guard missing');
}
if (!str_contains($controller, 'name="critical_reviewed"')) {
    throw new RuntimeException('Critical-code acknowledgement checkbox missing');
}

echo 'PASS settings update policy: '.count($ordinaryAllowed).' ordinary allowed, '.count($criticalAllowed).
    ' critical allowed/review-gated, '.count($rejected).' blocked; server and UI acknowledgement guards present'.PHP_EOL;
