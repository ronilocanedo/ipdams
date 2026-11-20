# Rebuild local images from the checked-out source and start isolated QA; no Hostinger actions.
$ErrorActionPreference = 'Stop'
$localFolder = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = (Resolve-Path (Join-Path $localFolder '..\..')).Path
& (Join-Path $localFolder 'init.ps1')
if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) { throw 'QA secret initialization failed' }
Push-Location $repoRoot
try {
    & docker compose --env-file qa/local/.env.qa -f qa/local/compose.yaml config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose validation failed' }
    & docker compose --env-file qa/local/.env.qa -f qa/local/compose.yaml up -d --build db web
    if ($LASTEXITCODE -ne 0) { throw 'Docker startup failed. Production was not accessed.' }
    Write-Host 'Local-only IPDAMS QA: http://127.0.0.1:8877'
    Write-Host 'NEXT: see qa/local/README.md for schema gate and Playwright commands.'
} finally { Pop-Location }
