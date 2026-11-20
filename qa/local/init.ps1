# IPDAMS isolated QA: creates ONLY an ignored local environment file.
$ErrorActionPreference = 'Stop'
$localFolder = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = (Resolve-Path (Join-Path $localFolder '..\..')).Path
$path = Join-Path $localFolder '.env.qa'
if (Test-Path $path) {
    Write-Host "Existing QA secrets preserved: $path"
    exit 0
}
function New-QASecret {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+','A').Replace('/','B')
}
$lines = @(
    '# LOCAL TEST ENVIRONMENT ONLY. Never upload or commit this file.',
    "QA_DB_ROOT_PASSWORD=$(New-QASecret)",
    "QA_DB_PASSWORD=$(New-QASecret)",
    "QA_SETUP_TOKEN=$(New-QASecret)",
    'QA_HTTP_PORT=8877'
)
[System.IO.File]::WriteAllLines($path, [string[]]$lines, (New-Object System.Text.UTF8Encoding $false))
Write-Host "Generated isolated QA credentials: $path"
Write-Host 'Do not copy any Hostinger or production database credentials into this file.'
Write-Host "Repo: $repoRoot"
