param(
    [switch]$VerboseLog
)

$ErrorActionPreference = 'Stop'

# Gateway lab03 (porta 4030, sessão crm-sandbox-03)
$InstanceSlug = 'lab03'
$BaseUrl = 'http://127.0.0.1:4030'
$HealthPath = '/health'
$MaxFailuresBeforeRestart = 2
$RestartCooldownSeconds = 180
$HealthTimeoutSeconds = 10

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$StartCommand = Join-Path $RepoRoot 'services/whatsapp-web-gateway/start-gateway-lab03.bat'
$LogPath = Join-Path $RepoRoot 'storage/logs/gateway_watchdog_lab03.log'
$StatePath = Join-Path $RepoRoot 'storage/logs/gateway_watchdog_lab03.state.json'

function Write-Log {
    param([string]$Message)
    $timestamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    $line = "[$timestamp] [$InstanceSlug] $Message"
    $logDir = Split-Path $LogPath -Parent
    if (!(Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
    Add-Content -Path $LogPath -Value $line
    if ($VerboseLog) { Write-Output $line }
}

function Load-State {
    if (Test-Path $StatePath) {
        try { return Get-Content $StatePath -Raw | ConvertFrom-Json } catch { Write-Log "Estado corrompido, reiniciando contadores" }
    }
    return [pscustomobject]@{ failures = 0; lastRestartUtc = '' }
}

function Save-State { param([pscustomobject]$State) $State | ConvertTo-Json | Set-Content -Path $StatePath -Encoding UTF8 }

function Test-GatewayHealth {
    $uri = "$BaseUrl$HealthPath"
    try {
        $resp = Invoke-WebRequest -Uri $uri -TimeoutSec $HealthTimeoutSeconds -UseBasicParsing
        return @{ ok = $true; status = $resp.StatusCode; body = $resp.Content }
    } catch { return @{ ok = $false; error = $_.Exception.Message } }
}

if (!(Test-Path $StartCommand)) { Write-Log "Start command nao encontrado: $StartCommand"; exit 1 }

$state = Load-State
$health = Test-GatewayHealth

if ($health.ok) {
    Write-Log "Health OK (status ${($health.status)})"
    $state.failures = 0
    Save-State $state
    exit 0
}

$state.failures = [int]$state.failures + 1
Write-Log "Health FAIL #${($state.failures)}: ${($health.error)}"

if ($state.failures -ge $MaxFailuresBeforeRestart) {
    $now = [DateTimeOffset]::UtcNow
    $lastRestart = if ($state.lastRestartUtc) { [DateTimeOffset]::Parse($state.lastRestartUtc) } else { [DateTimeOffset]::MinValue }
    $elapsed = ($now - $lastRestart).TotalSeconds
    if ($elapsed -lt $RestartCooldownSeconds) {
        Write-Log "Cooldown ativo (${elapsed}s desde ultimo restart); nao reiniciando"
        Save-State $state
        exit 1
    }

    Write-Log "Reiniciando gateway via $StartCommand"
    try {
        Start-Process -FilePath $StartCommand -WorkingDirectory (Split-Path $StartCommand -Parent) -WindowStyle Hidden
        $state.lastRestartUtc = $now.ToString('o')
        $state.failures = 0
        Save-State $state
        Write-Log "Restart disparado com sucesso"
        exit 2
    } catch {
        Write-Log "Falha ao reiniciar: ${($_.Exception.Message)}"
        $state.lastRestartUtc = $now.ToString('o')
        Save-State $state
        exit 1
    }
}

Save-State $state
exit 1
