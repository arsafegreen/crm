Param(
    [string]$Root = "F:\SISTEMA - SAFEGREEN\XAMPP - PROD - A\htdocs",
    [int]$ArchiveKeepDays = 90,
    [int]$MediaKeepDays = 30
)

$ErrorActionPreference = 'SilentlyContinue'
Write-Host "[start] WhatsApp daily maintenance" -ForegroundColor Cyan

# 1) Rotate logs (12h/10d handled in the sub-script)
$rotateScript = Join-Path $Root "scripts\rotate_whatsapp_logs.ps1"
if (Test-Path $rotateScript) {
    Write-Host "[log] rotating logs" -ForegroundColor Yellow
    pwsh -File $rotateScript -Root $Root | Out-Null
}

# 2) Prune archived messages older than ArchiveKeepDays
try {
    $db = Join-Path $Root "storage\database.sqlite"
    if (Test-Path $db) {
        $days = [int]$ArchiveKeepDays
        $ts = [int][double]::Parse((Get-Date).ToUniversalTime().Subtract([datetime]"1970-01-01").TotalSeconds) - ($days * 86400)
        $php = @"
<?php
\$pdo = new PDO('sqlite:' . '$($db -replace "\\","/")');
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec('CREATE TABLE IF NOT EXISTS whatsapp_messages_archive (id INTEGER PRIMARY KEY AUTOINCREMENT, orig_id INTEGER, thread_id INTEGER, direction TEXT, message_type TEXT, content TEXT, ai_summary TEXT, suggestion_source TEXT, meta_message_id TEXT, status TEXT, sent_at INTEGER, created_at INTEGER, metadata TEXT, archived_at INTEGER);');
\$stmt = \$pdo->prepare('DELETE FROM whatsapp_messages_archive WHERE archived_at IS NOT NULL AND archived_at < :cutoff');
\$stmt->execute([':cutoff' => $ts]);
?>
"@
        php -r $php | Out-Null
        Write-Host "[archive] pruned entries older than $days days" -ForegroundColor Yellow
    }
} catch {
    Write-Host "[archive] prune failed: $_" -ForegroundColor Red
}

# 3) Checkpoint + incremental vacuum (rápido) + analyze
try {
    if (Test-Path $db) {
        $phpVacuum = @"
<?php
\$pdo = new PDO('sqlite:' . '$($db -replace "\\","/")');
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// checkpoint para liberar WAL e vacuo incremental para evitar bloqueio longo
\$pdo->exec('PRAGMA foreign_keys=ON; PRAGMA wal_checkpoint(TRUNCATE); PRAGMA incremental_vacuum(2000); ANALYZE;');
?>
"@
        php -r $phpVacuum | Out-Null
        Write-Host "[db] checkpoint + incremental vacuum + analyze done" -ForegroundColor Yellow
    }
} catch {
    Write-Host "[db] vacuum failed: $_" -ForegroundColor Red
}

# 4) Clean old media
$mediaDir = Join-Path $Root "storage\whatsapp-media"
if (Test-Path $mediaDir) {
    Write-Host "[media] removing files older than $MediaKeepDays days" -ForegroundColor Yellow
    Get-ChildItem $mediaDir -Recurse -File | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-1 * $MediaKeepDays) } | Remove-Item -Force -ErrorAction SilentlyContinue
}

# 5) Optional: clean stale whatsapp-web cache older than 7 days to keep gateway lean
$waWeb = Join-Path $Root "storage\whatsapp-web"
if (Test-Path $waWeb) {
    Get-ChildItem $waWeb -Recurse -File | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-7) } | Remove-Item -Force -ErrorAction SilentlyContinue
}

Write-Host "[done] WhatsApp daily maintenance" -ForegroundColor Green
