Param(
    [string]$Root = "F:\SISTEMA - SAFEGREEN\XAMPP - PROD - A\htdocs",
    [int]$KeepDays = 10
)

$ErrorActionPreference = 'SilentlyContinue'
$logs = @(
    "$Root\storage\logs\whatsapp_alt_webhook.log",
    "$Root\storage\logs\whatsapp_alt_webhook_errors.log",
    "$Root\storage\logs\whatsapp-sandbox.log",
    "$Root\storage\logs\alerts.log",
    "$Root\services\whatsapp-web-gateway\gateway.log",
    "$Root\services\whatsapp-web-gateway\gateway-runtime.log",
    "$Root\services\whatsapp-web-gateway\gateway-lab01-run.log",
    "$Root\services\whatsapp-web-gateway\gateway-lab02-run.log"
)

$archiveDir = "$Root\storage\logs\archive"
if (-not (Test-Path $archiveDir)) { New-Item -ItemType Directory -Force -Path $archiveDir | Out-Null }

$timestamp = (Get-Date).ToString('yyyyMMdd_HHmm')

foreach ($file in $logs) {
    if (-not (Test-Path $file)) { continue }
    try {
        $name = Split-Path $file -Leaf
        $archive = Join-Path $archiveDir "$($name)-$timestamp.log"
        Move-Item -Force $file $archive
        Compress-Archive -Path $archive -DestinationPath "$archive.zip" -Force
        Remove-Item -Force $archive
        New-Item -ItemType File -Force -Path $file | Out-Null
    } catch {}
}

Get-ChildItem $archiveDir -File -Recurse | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-1 * $KeepDays) } | Remove-Item -Force
