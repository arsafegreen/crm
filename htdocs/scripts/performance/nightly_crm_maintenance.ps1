param(
    [string]$XamppRoot = "F:\sistemas\xampp-dwv",
    [string]$PhpExecutable = "F:\sistemas\xampp-dwv\php\php.exe",
    [string]$MysqlClient = "F:\sistemas\xampp-dwv\mysql\bin\mysql.exe",
    [string]$MysqlDefaultsFile = "F:\sistemas\xampp-dwv\config\performance\mysql_maintenance.cnf",
    [string]$CloudflareServiceName = "cloudflared",
    [int]$CacheTtlSeconds = 900
)

$LogDirectory = Join-Path $XamppRoot "logs"
$LogFile = Join-Path $LogDirectory "nightly_crm_maintenance.log"
if (-not (Test-Path $LogDirectory)) {
    New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
}

function Write-Log {
    param(
        [string]$Message,
        [string]$Level = 'INFO'
    )
    $timestamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    $entry = "[$timestamp] [$Level] $Message"
    $entry | Out-File -FilePath $LogFile -Append -Encoding utf8
    Write-Output $entry
}

function Assert-TimeWindow {
    $hour = (Get-Date).Hour
    if ($hour -ge 20 -or $hour -lt 6) {
        Write-Log "Dentro da janela permitida (20h-06h)."
        return
    }
    Write-Log "Fora da janela (20h-06h). Abortando execução." 'WARN'
    exit 0
}

function Clear-PHPCaches {
    Write-Log "Limpando caches do PHP e diretórios temporários..."
    $tmpPaths = @(
        Join-Path $XamppRoot 'tmp',
        Join-Path $XamppRoot 'php', 'logs', 'opcache',
        Join-Path $XamppRoot 'storage', 'framework', 'cache'
    )

    foreach ($path in $tmpPaths) {
        if (Test-Path $path) {
            Get-ChildItem -Path $path -Recurse -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
            Write-Log "Removido conteúdo de $path"
        }
    }

    if (Test-Path $PhpExecutable) {
        & $PhpExecutable -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache reset'; }" | Out-Null
        Write-Log "OPcache resetado via PHP CLI."
    } else {
        Write-Log "PHP CLI não encontrado em $PhpExecutable" 'WARN'
    }
}

function Optimize-Databases {
    if (-not (Test-Path $MysqlClient)) {
        Write-Log "mysql.exe não encontrado em $MysqlClient" 'ERROR'
        return
    }
    if (-not (Test-Path $MysqlDefaultsFile)) {
        Write-Log "Arquivo de credenciais não encontrado: $MysqlDefaultsFile" 'ERROR'
        return
    }

    Write-Log "Iniciando OPTIMIZE TABLE em todos os bancos usuários..."
    $dbListCommand = "SHOW DATABASES WHERE `Database` NOT IN ('mysql','information_schema','performance_schema','sys');"
    $databases = & $MysqlClient --defaults-extra-file=$MysqlDefaultsFile -N -B -e $dbListCommand
    $dbNames = $databases -split "\r?\n" | Where-Object { $_ -and $_.Trim() -ne '' }

    foreach ($db in $dbNames) {
        Write-Log "Processando banco: $db"
        $tables = & $MysqlClient --defaults-extra-file=$MysqlDefaultsFile -N -B -D $db -e "SHOW TABLES;"
        $tableNames = $tables -split "\r?\n" | Where-Object { $_ -and $_.Trim() -ne '' }
        foreach ($table in $tableNames) {
            Write-Log "OPTIMIZE TABLE $db.$table"
            & $MysqlClient --defaults-extra-file=$MysqlDefaultsFile -N -B -D $db -e "OPTIMIZE TABLE `$table`;" | Out-Null
        }
    }
    Write-Log "OPTIMIZE TABLE finalizado."
}

function Restart-XamppServices {
    Write-Log "Reiniciando Apache e MySQL..."
    $apacheStop = Join-Path $XamppRoot 'apache_stop.bat'
    $apacheStart = Join-Path $XamppRoot 'apache_start.bat'
    $mysqlStop = Join-Path $XamppRoot 'mysql_stop.bat'
    $mysqlStart = Join-Path $XamppRoot 'mysql_start.bat'

    foreach ($script in @($apacheStop, $apacheStart, $mysqlStop, $mysqlStart)) {
        if (-not (Test-Path $script)) {
            Write-Log "Script não encontrado: $script" 'WARN'
        }
    }

    & $apacheStop | Out-Null
    & $mysqlStop | Out-Null
    Start-Sleep -Seconds 5
    & $mysqlStart | Out-Null
    & $apacheStart | Out-Null
    Write-Log "Serviços XAMPP reiniciados."
}

function Ensure-CloudflareTunnel {
    Write-Log "Verificando serviço Cloudflare Tunnel ($CloudflareServiceName)..."
    try {
        $service = Get-Service -Name $CloudflareServiceName -ErrorAction Stop
        if ($service.Status -ne 'Running') {
            Start-Service -Name $CloudflareServiceName
            Write-Log "Serviço $CloudflareServiceName reiniciado."
        } else {
            Write-Log "Serviço $CloudflareServiceName já estava em execução."
        }
    } catch {
        Write-Log "Serviço $CloudflareServiceName não encontrado: $($_.Exception.Message)" 'WARN'
    }
}

function Warmup-Cache {
    $cacheScript = Join-Path $XamppRoot 'scripts' 'performance' 'cache_crm_clients.php'
    if (-not (Test-Path $cacheScript)) {
        Write-Log "Script de cache não encontrado: $cacheScript" 'WARN'
        return
    }

    $phpCli = $PhpExecutable
    if (-not (Test-Path $phpCli)) {
        Write-Log "PHP CLI não encontrado para aquecer cache." 'WARN'
        return
    }

    Write-Log "Aquecendo cache decodificado (TTL=${CacheTtlSeconds}s)..."
    & $phpCli $cacheScript --warmup --ttl=$CacheTtlSeconds | ForEach-Object { Write-Log $_ }
}

try {
    Write-Log "===== Rotina noturna iniciada ====="
    Assert-TimeWindow
    Clear-PHPCaches
    Warmup-Cache
    Optimize-Databases
    Restart-XamppServices
    Ensure-CloudflareTunnel
    Write-Log "===== Rotina noturna concluída ====="
} catch {
    Write-Log "Erro inesperado: $($_.Exception.Message)" 'ERROR'
    exit 1
}
