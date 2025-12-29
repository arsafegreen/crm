param(
    [int]$AccountId,
    [string]$Folders,
    [int]$Limit = 100,
    [int]$IntervalSeconds = 30
)

$ErrorActionPreference = 'Stop'

$scriptsDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent (Split-Path -Parent $scriptsDir)
$phpExecutable = Join-Path $projectRoot 'php\php.exe'
$phpScript = Join-Path $scriptsDir 'email/sync_mailboxes.php'

if (-not (Test-Path $phpExecutable)) {
    Write-Error "PHP não encontrado em $phpExecutable"
    exit 1
}

if (-not (Test-Path $phpScript)) {
    Write-Error "Arquivo PHP não encontrado em $phpScript"
    exit 1
}

$phpArguments = @($phpScript)
if ($AccountId) {
    $phpArguments += "--account_id=$AccountId"
}
if ($Folders) {
    $phpArguments += "--folders=$Folders"
}
if ($Limit) {
    $phpArguments += "--limit=$Limit"
}

Write-Host "Sincronizador iniciado. Pressione Ctrl+C para parar." -ForegroundColor Cyan

while ($true) {
    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Write-Host "[$timestamp] Rodando $phpExecutable $($phpArguments -join ' ')"

    & $phpExecutable @phpArguments
    $exitCode = $LASTEXITCODE

    if ($exitCode -ne 0) {
        Write-Warning "Sincronização terminou com código $exitCode"
    }

    Write-Host "[$timestamp] Aguardando $IntervalSeconds segundos..."
    Start-Sleep -Seconds $IntervalSeconds
}
